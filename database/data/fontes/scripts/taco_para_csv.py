import re,csv,sys
cats=["Cereais e derivados","Verduras, hortaliças e derivados","Frutas e derivados","Gorduras e óleos","Pescados e frutos do mar","Carnes e derivados","Leite e derivados","Bebidas (alcoólicas e não alcoólicas)","Ovos e derivados","Produtos açucarados","Miscelâneas","Outros alimentos industrializados","Alimentos preparados","Leguminosas e derivados","Nozes e sementes"]
lines=open('taco.txt').read().split('\n')
start=next(i for i,l in enumerate(lines) if 'Tabela 1. Composição' in l)
end=2763
partA=False; cat=None; rows={}; last=None
for l in lines[start:end]:
    l=l.replace('\f','')
    s=l.strip()
    if 'Umidade' in l: partA=True; continue
    if re.search(r'Manganês|Retinol|Tiamina|Sódio|Potássio|Fósforo',l): partA=False; continue
    if s in cats: cat=s; continue
    m=re.match(r'^\s*(\d{1,3})\s+(\S.*?)\s{2,}(\S.*)$',l)
    if partA and m:
        n=int(m.group(1)); desc=m.group(2).strip(); vals=m.group(3).split()
        if len(vals)>=7 and n not in rows:
            rows[n]=dict(name=desc,category=cat,kcal=vals[1],protein=vals[3],fat=vals[4],carbs=vals[6]); last=n
            continue
    last=None
if __name__=='__main__':
    print(len(rows))
    missing=[n for n in range(1,598) if n not in rows]
    print('faltando',len(missing),missing[:60])
    for n in [3,100,200,300,400,500,597]: print(n,rows.get(n))

def gerar(caminho):
    with open(caminho,'w',newline='') as f:
        w=csv.writer(f); w.writerow(['name','category','kcal','protein','carbs','fat','source'])
        for n in sorted(rows):
            r=rows[n]; nome=re.sub(r'\s\d{1,2}$','',r['name'])
            w.writerow([nome,r['category'],r['kcal'],r['protein'],r['carbs'],r['fat'],'TACO 4ª ed.'])
